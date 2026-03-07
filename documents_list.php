<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'patients';
$selectedEntityId = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;

$allowedTabs = ['patients', 'professionals', 'employees'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'patients';
}

// Mapear tab para entity_type
$entityTypeMap = [
    'patients' => 'patient',
    'professionals' => 'professional',
    'employees' => 'company' // Usando 'company' pois é o que existe na tabela
];
$entityType = $entityTypeMap[$tab];

// Buscar entidades (pacientes/profissionais) que têm documentos
$entitiesStmt = null;
$entities = [];

if ($tab === 'patients') {
    $entitiesStmt = db()->prepare("
        SELECT p.id, p.full_name as name,
               (SELECT COUNT(*) FROM documents d WHERE d.entity_type = 'patient' AND d.entity_id = p.id AND d.status = 'active') as document_count
        FROM patients p
        WHERE p.deleted_at IS NULL
        HAVING document_count > 0
        ORDER BY p.full_name ASC
    ");
    $entitiesStmt->execute();
} else {
    $entitiesStmt = db()->prepare("
        SELECT u.id, u.name,
               (SELECT COUNT(*) FROM documents d WHERE d.entity_type = ? AND d.entity_id = u.id AND d.status = 'active') as document_count
        FROM users u
        WHERE u.deleted_at IS NULL
        HAVING document_count > 0
        ORDER BY u.name ASC
    ");
    $entitiesStmt->execute([$entityType]);
}

$entities = $entitiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar documentos da entidade selecionada
$documents = [];
if ($selectedEntityId > 0) {
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
echo '<div style="font-size:22px;font-weight:900">📁 Gestão Documental</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Documentos consolidados por paciente, profissional ou funcionário</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
if ($selectedEntityId > 0) {
    echo '<a class="btn btnPrimary" href="/documents_upload.php?entity_type=' . h($entityType) . '&entity_id=' . $selectedEntityId . '">📤 Upload Documento</a>';
}
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

// Abas de navegação
echo '<div style="margin-top:20px;border-bottom:2px solid #e5e7eb">';
echo '<div style="display:flex;gap:4px">';

$tabs = [
    'patients' => '👤 Pacientes',
    'professionals' => '🩺 Profissionais',
    'employees' => '👔 Funcionários'
];

foreach ($tabs as $tabKey => $tabLabel) {
    $isActive = $tab === $tabKey;
    $activeStyle = $isActive ? 'background:#00a884;color:white;border-color:#00a884' : 'background:white;color:#667781;border-color:transparent';
    echo '<a href="/documents_list.php?tab=' . $tabKey . '" style="padding:12px 24px;text-decoration:none;font-weight:600;border:2px solid;border-bottom:none;border-radius:8px 8px 0 0;' . $activeStyle . '">' . $tabLabel . '</a>';
}

echo '</div>';
echo '</div>';

echo '</section>';

// Grid com 2 colunas: Lista de entidades | Documentos da entidade
echo '<section class="card col4">';
echo '<div style="font-size:16px;font-weight:700;margin-bottom:12px">📂 ' . ($tab === 'patients' ? 'Pacientes' : ($tab === 'professionals' ? 'Profissionais' : 'Funcionários')) . '</div>';
echo '<div style="max-height:600px;overflow-y:auto">';

if (count($entities) === 0) {
    echo '<div class="pill" style="padding:12px;text-align:center;color:#667781">Nenhum registro com documentos</div>';
} else {
    foreach ($entities as $entity) {
        $isSelected = $selectedEntityId === (int)$entity['id'];
        $bgColor = $isSelected ? '#e7f8f4' : '#f8f9fa';
        $borderColor = $isSelected ? '#00a884' : '#e5e7eb';
        
        echo '<a href="/documents_list.php?tab=' . $tab . '&entity_id=' . (int)$entity['id'] . '" style="display:block;padding:12px;margin-bottom:8px;background:' . $bgColor . ';border:2px solid ' . $borderColor . ';border-radius:8px;text-decoration:none;color:#111b21">';
        echo '<div style="font-weight:600;font-size:14px">' . h($entity['name']) . '</div>';
        echo '<div style="font-size:12px;color:#667781;margin-top:4px">' . (int)$entity['document_count'] . ' documento(s)</div>';
        echo '</a>';
    }
}

echo '</div>';
echo '</section>';

echo '<section class="card col8">';
echo '<div style="font-size:16px;font-weight:700;margin-bottom:12px">📄 Documentos</div>';

if ($selectedEntityId === 0) {
    echo '<div class="pill" style="padding:20px;text-align:center;color:#667781">';
    echo '← Selecione um registro para ver os documentos';
    echo '</div>';
} elseif (count($documents) === 0) {
    echo '<div class="pill" style="padding:20px;text-align:center;color:#667781">';
    echo 'Nenhum documento encontrado';
    echo '</div>';
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
            echo '<a class="btn" href="' . h($doc['file_path']) . '" target="_blank">📥 Download</a> ';
        }
        echo '<a class="btn" href="/documents_view.php?id=' . (int)$doc['id'] . '">👁️ Ver</a>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
