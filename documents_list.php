<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'patients';
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$selectedEntityId = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;

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

// Buscar lista de entidades (pessoas) que têm documentos
$entities = [];

if ($tab === 'patients') {
    $sql = "
        SELECT DISTINCT p.id, p.full_name as name,
               (SELECT COUNT(*) FROM documents d WHERE d.entity_type = 'patient' AND d.entity_id = p.id AND d.status = 'active') as document_count
        FROM patients p
        INNER JOIN documents d ON d.entity_type = 'patient' AND d.entity_id = p.id AND d.status = 'active'
        WHERE p.deleted_at IS NULL
    ";
    
    if ($searchQuery !== '') {
        $sql .= " AND p.full_name LIKE ?";
    }
    
    $sql .= " GROUP BY p.id, p.full_name ORDER BY p.full_name ASC";
    
    $stmt = db()->prepare($sql);
    if ($searchQuery !== '') {
        $stmt->execute(['%' . $searchQuery . '%']);
    } else {
        $stmt->execute();
    }
    $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql = "
        SELECT DISTINCT u.id, u.name,
               (SELECT COUNT(*) FROM documents d WHERE d.entity_type = ? AND d.entity_id = u.id AND d.status = 'active') as document_count
        FROM users u
        INNER JOIN documents d ON d.entity_type = ? AND d.entity_id = u.id AND d.status = 'active'
    ";
    
    if ($searchQuery !== '') {
        $sql .= " WHERE u.name LIKE ?";
    }
    
    $sql .= " GROUP BY u.id, u.name ORDER BY u.name ASC";
    
    $stmt = db()->prepare($sql);
    if ($searchQuery !== '') {
        $stmt->execute([$entityType, $entityType, '%' . $searchQuery . '%']);
    } else {
        $stmt->execute([$entityType, $entityType]);
    }
    $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar documentos da entidade selecionada (mais recente ao mais antigo)
$documents = [];
$selectedEntityName = '';

if ($selectedEntityId > 0) {
    // Buscar nome da entidade selecionada
    if ($tab === 'patients') {
        $nameStmt = db()->prepare("SELECT full_name as name FROM patients WHERE id = ?");
        $nameStmt->execute([$selectedEntityId]);
        $nameResult = $nameStmt->fetch(PDO::FETCH_ASSOC);
        $selectedEntityName = $nameResult ? $nameResult['name'] : '';
    } else {
        $nameStmt = db()->prepare("SELECT name FROM users WHERE id = ?");
        $nameStmt->execute([$selectedEntityId]);
        $nameResult = $nameStmt->fetch(PDO::FETCH_ASSOC);
        $selectedEntityName = $nameResult ? $nameResult['name'] : '';
    }
    
    // Buscar documentos
    $docsStmt = db()->prepare("
        SELECT d.id, d.title, d.category, d.status, d.created_at,
               (SELECT MAX(v.version_no) FROM document_versions v WHERE v.document_id = d.id) as last_version,
               (SELECT v.stored_path FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as file_path,
               (SELECT v.file_size FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as file_size,
               (SELECT u.name FROM document_versions v LEFT JOIN users u ON u.id = v.uploaded_by_user_id WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as uploaded_by_name
        FROM documents d
        WHERE d.entity_type = ? AND d.entity_id = ? AND d.status = 'active'
        ORDER BY d.created_at DESC
    ");
    $docsStmt->execute([$entityType, $selectedEntityId]);
    $documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC);
}

view_header('Gestão Documental');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gestão Documental</div>';
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

// Layout de 2 colunas: Lista de nomes | Documentos
echo '<div style="display:flex;gap:16px;margin-top:16px">';

// Coluna esquerda: Lista de nomes
echo '<div style="flex:0 0 300px;background:white;padding:16px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1)">';
echo '<div style="margin-bottom:12px">';
echo '<form method="get" action="/documents_list.php">';
echo '<input type="hidden" name="tab" value="' . h($tab) . '">';
echo '<input type="text" name="q" value="' . h($searchQuery) . '" placeholder="Buscar..." style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</form>';
echo '</div>';

echo '<div style="max-height:600px;overflow-y:auto">';
if (count($entities) === 0) {
    echo '<div style="padding:12px;text-align:center;color:#667781">';
    echo $searchQuery !== '' ? 'Nenhum resultado para "' . h($searchQuery) . '"' : 'Nenhum registro com documentos';
    echo '</div>';
} else {
    echo '<ul style="list-style:none;margin:0;padding:0">';
    foreach ($entities as $entity) {
        $isSelected = $selectedEntityId === (int)$entity['id'];
        $bgColor = $isSelected ? '#f0f9ff' : 'transparent';
        $fontWeight = $isSelected ? '700' : '400';
        $color = $isSelected ? '#00a884' : '#111b21';
        
        echo '<li style="margin:0;padding:0">';
        echo '<a href="/documents_list.php?tab=' . h($tab) . '&entity_id=' . (int)$entity['id'] . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '') . '" style="display:block;padding:10px 12px;text-decoration:none;color:' . $color . ';font-weight:' . $fontWeight . ';background:' . $bgColor . ';border-radius:4px;transition:all 0.15s">';
        echo h($entity['name']);
        echo '<span style="font-size:12px;color:#667781;margin-left:8px">(' . (int)$entity['document_count'] . ')</span>';
        echo '</a>';
        echo '</li>';
    }
    echo '</ul>';
}
echo '</div>';
echo '</div>';

// Coluna direita: Documentos
echo '<div style="flex:1;background:white;padding:16px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1)">';
if ($selectedEntityId === 0) {
    echo '<div class="pill" style="padding:40px;text-align:center;color:#667781">';
    echo '<svg style="width:48px;height:48px;margin:0 auto 16px;opacity:0.3" fill="currentColor" viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Selecione uma pessoa</div>';
    echo '<div style="font-size:14px">Clique em um nome à esquerda para ver os documentos</div>';
    echo '</div>';
} else {
    echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">';
    echo '<div>';
    echo '<div style="font-size:18px;font-weight:700">' . h($selectedEntityName) . '</div>';
    echo '<div style="font-size:14px;color:#667781;margin-top:4px">' . count($documents) . ' documento(s)</div>';
    echo '</div>';
    echo '<a class="btn btnPrimary" href="/documents_upload.php?entity_type=' . h($entityType) . '&entity_id=' . $selectedEntityId . '"><svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>Upload</a>';
    echo '</div>';
    
    if (count($documents) === 0) {
        echo '<div class="pill" style="padding:20px;text-align:center;color:#667781">Nenhum documento encontrado</div>';
    } else {
        echo '<div style="overflow:auto">';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>Título</th><th>Categoria</th><th>Versão</th><th>Tamanho</th><th>Enviado por</th><th>Data</th><th style="text-align:right">Ações</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($documents as $doc) {
            $fileSize = (int)($doc['file_size'] ?? 0);
            $fileSizeFormatted = $fileSize > 1048576 ? number_format($fileSize / 1048576, 2) . ' MB' : ($fileSize > 0 ? number_format($fileSize / 1024, 2) . ' KB' : '-');
            $version = $doc['last_version'] ? 'v' . $doc['last_version'] : '-';
            
            echo '<tr>';
            echo '<td style="font-weight:600">' . h($doc['title'] ?? 'Sem título') . '</td>';
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
}

echo '</div>';
echo '</div>';

echo '</div>';

view_footer();
