<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
// rbac_require_permission('billing.manage'); // TODO: Configurar permissão no sistema

$tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'awaiting_documents';
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowedTabs = ['awaiting_documents', 'awaiting_approval', 'approved', 'completed'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'awaiting_documents';
}

// Buscar atendimentos com base no status
$assignments = [];

$sql = "
    SELECT 
        pa.id,
        pa.patient_id,
        pa.professional_user_id,
        pa.session_quantity,
        pa.payment_value,
        pa.specialty,
        pa.service_type,
        pa.status,
        pa.approved_at,
        pa.admitted_at,
        pa.completed_at,
        p.full_name as patient_name,
        u.name as professional_name,
        (SELECT COUNT(*) FROM billing_document_requirements bdr WHERE bdr.assignment_id = pa.id AND bdr.status = 'pending') as pending_docs,
        (SELECT COUNT(*) FROM billing_document_requirements bdr WHERE bdr.assignment_id = pa.id AND bdr.status = 'uploaded') as uploaded_docs,
        (SELECT COUNT(*) FROM billing_document_requirements bdr WHERE bdr.assignment_id = pa.id) as total_docs
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN users u ON u.id = pa.professional_user_id
    WHERE 1=1
";

if ($tab === 'awaiting_documents') {
    $sql .= " AND pa.status = 'admitted'";
} elseif ($tab === 'awaiting_approval') {
    $sql .= " AND pa.status = 'awaiting_financial_approval'";
} elseif ($tab === 'approved') {
    $sql .= " AND pa.status IN ('approved')";
} elseif ($tab === 'completed') {
    $sql .= " AND pa.status = 'completed'";
}

if ($searchQuery !== '') {
    $sql .= " AND (p.full_name LIKE ? OR u.name LIKE ?)";
}

$sql .= " ORDER BY pa.created_at DESC";

$stmt = db()->prepare($sql);
if ($searchQuery !== '') {
    $searchParam = '%' . $searchQuery . '%';
    $stmt->execute([$searchParam, $searchParam]);
} else {
    $stmt->execute();
}
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

view_header('Faturamento');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Faturamento</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Gestão de documentos e liberação financeira</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

// Abas de navegação
echo '<div style="margin-top:20px;border-bottom:2px solid #e5e7eb">';
echo '<div style="display:flex;gap:4px">';

$tabs = [
    'awaiting_documents' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm0 4c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm6 12H6v-1.4c0-2 4-3.1 6-3.1s6 1.1 6 3.1V19z"/></svg>Aguardando Documentos',
    'awaiting_approval' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>Aguardando Aprovação',
    'approved' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg>Aprovados',
    'completed' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>Concluídos'
];

foreach ($tabs as $tabKey => $tabLabel) {
    $isActive = $tab === $tabKey;
    $activeStyle = $isActive ? 'background:#00a884;color:white;border-color:#00a884' : 'background:white;color:#667781;border-color:transparent';
    echo '<a href="/faturamento_list.php?tab=' . $tabKey . '" style="padding:12px 24px;text-decoration:none;font-weight:600;border:2px solid;border-bottom:none;border-radius:8px 8px 0 0;' . $activeStyle . '">' . $tabLabel . '</a>';
}

echo '</div>';
echo '</div>';

echo '</section>';

// Filtro de pesquisa
echo '<section class="card col12" style="margin-top:16px">';
echo '<form method="get" action="/faturamento_list.php" style="margin-bottom:0">';
echo '<input type="hidden" name="tab" value="' . h($tab) . '">';
echo '<input type="text" name="q" value="' . h($searchQuery) . '" placeholder="Buscar por paciente ou profissional..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</form>';
echo '</section>';

// Tabela de atendimentos
echo '<section class="card col12" style="margin-top:16px">';

if (count($assignments) === 0) {
    echo '<div style="padding:40px;text-align:center;color:#667781">';
    echo $searchQuery !== '' ? 'Nenhum resultado para "' . h($searchQuery) . '"' : 'Nenhum atendimento encontrado';
    echo '</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Paciente</th><th>Profissional</th><th>Especialidade</th><th>Sessões</th><th>Valor/Sessão</th><th>Total</th>';
    
    if ($tab === 'awaiting_documents') {
        echo '<th>Documentos</th>';
    } elseif ($tab === 'awaiting_approval') {
        echo '<th>Status Docs</th>';
    }
    
    echo '<th>Data</th><th style="text-align:right">Ações</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($assignments as $assignment) {
        $totalValue = (float)$assignment['payment_value'] * (int)$assignment['session_quantity'];
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . h($assignment['patient_name']) . '</td>';
        echo '<td>' . h($assignment['professional_name']) . '</td>';
        echo '<td>' . h($assignment['specialty'] ?? '-') . '</td>';
        echo '<td>' . (int)$assignment['session_quantity'] . '</td>';
        echo '<td>R$ ' . number_format((float)$assignment['payment_value'], 2, ',', '.') . '</td>';
        echo '<td style="font-weight:600">R$ ' . number_format($totalValue, 2, ',', '.') . '</td>';
        
        if ($tab === 'awaiting_documents') {
            $pendingDocs = (int)$assignment['pending_docs'];
            $totalDocs = (int)$assignment['total_docs'];
            $docsColor = $pendingDocs > 0 ? '#ef4444' : '#10b981';
            echo '<td style="color:' . $docsColor . ';font-weight:600">' . $pendingDocs . '/' . $totalDocs . ' pendentes</td>';
        } elseif ($tab === 'awaiting_approval') {
            $uploadedDocs = (int)$assignment['uploaded_docs'];
            $totalDocs = (int)$assignment['total_docs'];
            echo '<td style="color:#f59e0b;font-weight:600">' . $uploadedDocs . '/' . $totalDocs . ' enviados</td>';
        }
        
        $dateField = $assignment['completed_at'] ?? $assignment['admitted_at'] ?? $assignment['approved_at'];
        echo '<td>' . ($dateField ? date('d/m/Y', strtotime($dateField)) : '-') . '</td>';
        echo '<td style="text-align:right">';
        echo '<a class="btn" href="/faturamento_view.php?id=' . (int)$assignment['id'] . '">Ver detalhes</a>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
