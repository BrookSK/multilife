<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'patients';
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowedTabs = ['patients', 'professionals', 'employees'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'patients';
}

// Mapear tab para entity_type
$entityTypeMap = [
    'patients' => 'patient',
    'professionals' => 'professional',
    'employees' => 'company'
];
$entityType = $entityTypeMap[$tab];

// Buscar documentos com informações da entidade
$documents = [];

if ($tab === 'patients') {
    $sql = "
        SELECT d.id, d.title, d.category, d.status, d.created_at, d.entity_id,
               p.full_name as entity_name,
               (SELECT MAX(v.version_no) FROM document_versions v WHERE v.document_id = d.id) as last_version,
               (SELECT v.stored_path FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as file_path,
               (SELECT v.file_size FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as file_size,
               (SELECT u.name FROM document_versions v LEFT JOIN users u ON u.id = v.uploaded_by_user_id WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as uploaded_by_name
        FROM documents d
        LEFT JOIN patients p ON p.id = d.entity_id
        WHERE d.entity_type = 'patient' AND d.status = 'active' AND p.deleted_at IS NULL
    ";
    
    if ($searchQuery !== '') {
        $sql .= " AND (p.full_name LIKE ? OR d.title LIKE ? OR d.category LIKE ?)";
    }
    
    $sql .= " ORDER BY p.full_name ASC, d.created_at DESC";
    
    $stmt = db()->prepare($sql);
    if ($searchQuery !== '') {
        $searchParam = '%' . $searchQuery . '%';
        $stmt->execute([$searchParam, $searchParam, $searchParam]);
    } else {
        $stmt->execute();
    }
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql = "
        SELECT d.id, d.title, d.category, d.status, d.created_at, d.entity_id,
               u.name as entity_name,
               (SELECT MAX(v.version_no) FROM document_versions v WHERE v.document_id = d.id) as last_version,
               (SELECT v.stored_path FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as file_path,
               (SELECT v.file_size FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as file_size,
               (SELECT u2.name FROM document_versions v LEFT JOIN users u2 ON u2.id = v.uploaded_by_user_id WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as uploaded_by_name
        FROM documents d
        LEFT JOIN users u ON u.id = d.entity_id
        WHERE d.entity_type = ? AND d.status = 'active'
    ";
    
    if ($searchQuery !== '') {
        $sql .= " AND (u.name LIKE ? OR d.title LIKE ? OR d.category LIKE ?)";
    }
    
    $sql .= " ORDER BY u.name ASC, d.created_at DESC";
    
    $stmt = db()->prepare($sql);
    if ($searchQuery !== '') {
        $searchParam = '%' . $searchQuery . '%';
        $stmt->execute([$entityType, $searchParam, $searchParam, $searchParam]);
    } else {
        $stmt->execute([$entityType]);
    }
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

view_header('Gestão Documental');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900"><svg style="width:24px;height:24px;margin-right:8px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>Gestão Documental</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Documentos consolidados por paciente, profissional ou funcionário</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

// Abas de navegação
echo '<div style="margin-top:20px;border-bottom:2px solid #e5e7eb">';
echo '<div style="display:flex;gap:4px">';

$tabs = [
    'patients' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>Pacientes',
    'professionals' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>Profissionais',
    'employees' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M20 6h-4V4c0-1.1-.9-2-2-2h-4c-1.1 0-2 .9-2 2v2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zM10 4h4v2h-4V4zm10 16H4V8h16v12z"/></svg>Funcionários'
];

foreach ($tabs as $tabKey => $tabLabel) {
    $isActive = $tab === $tabKey;
    $activeStyle = $isActive ? 'background:#00a884;color:white;border-color:#00a884' : 'background:white;color:#667781;border-color:transparent';
    echo '<a href="/documents_list.php?tab=' . $tabKey . '" style="padding:12px 24px;text-decoration:none;font-weight:600;border:2px solid;border-bottom:none;border-radius:8px 8px 0 0;' . $activeStyle . '">' . $tabLabel . '</a>';
}

echo '</div>';
echo '</div>';

echo '</section>';

// Filtro de pesquisa e tabela de documentos
echo '<section class="card col12">';
echo '<form method="get" action="/documents_list.php" style="margin-bottom:16px">';
echo '<input type="hidden" name="tab" value="' . h($tab) . '">';
echo '<div style="display:flex;gap:10px;align-items:center">';
echo '<input type="text" name="q" value="' . h($searchQuery) . '" placeholder="Buscar por nome, título ou categoria..." style="flex:1;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '<button type="submit" class="btn btnPrimary"><svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>Buscar</button>';
if ($searchQuery !== '') {
    echo '<a href="/documents_list.php?tab=' . h($tab) . '" class="btn">Limpar</a>';
}
echo '</div>';
echo '</form>';

if (count($documents) === 0) {
    echo '<div class="pill" style="padding:20px;text-align:center;color:#667781">';
    echo $searchQuery !== '' ? 'Nenhum documento encontrado para "' . h($searchQuery) . '"' : 'Nenhum documento encontrado';
    echo '</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>' . ($tab === 'patients' ? 'Paciente' : ($tab === 'professionals' ? 'Profissional' : 'Funcionário')) . '</th>';
    echo '<th>Título</th><th>Categoria</th><th>Versão</th><th>Tamanho</th><th>Enviado por</th><th>Data</th><th style="text-align:right">Ações</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($documents as $doc) {
        $fileSize = (int)($doc['file_size'] ?? 0);
        $fileSizeFormatted = $fileSize > 1048576 ? number_format($fileSize / 1048576, 2) . ' MB' : ($fileSize > 0 ? number_format($fileSize / 1024, 2) . ' KB' : '-');
        $version = $doc['last_version'] ? 'v' . $doc['last_version'] : '-';
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . h($doc['entity_name'] ?? '-') . '</td>';
        echo '<td>' . h($doc['title'] ?? 'Sem título') . '</td>';
        echo '<td>' . h($doc['category'] ?? '-') . '</td>';
        echo '<td>' . h($version) . '</td>';
        echo '<td>' . $fileSizeFormatted . '</td>';
        echo '<td>' . h($doc['uploaded_by_name'] ?? '-') . '</td>';
        echo '<td>' . date('d/m/Y', strtotime($doc['created_at'])) . '</td>';
        echo '<td style="text-align:right">';
        if (!empty($doc['file_path'])) {
            echo '<a class="btn" href="' . h($doc['file_path']) . '" target="_blank"><svg style="width:14px;height:14px;margin-right:4px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>Download</a> ';
        }
        echo '<a class="btn" href="/documents_view.php?id=' . (int)$doc['id'] . '"><svg style="width:14px;height:14px;margin-right:4px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>Ver</a>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
