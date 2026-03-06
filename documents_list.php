<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'patients';
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$selectedFolderId = isset($_GET['folder_id']) ? (int)$_GET['folder_id'] : 0;

$allowedTabs = ['patients', 'professionals', 'employees'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'patients';
}

// Mapear tab para entity_type
$entityTypeMap = [
    'patients' => 'patient',
    'professionals' => 'professional',
    'employees' => 'employee'
];
$entityType = $entityTypeMap[$tab];

// Buscar pastas de documentos por tipo
$foldersStmt = db()->prepare("
    SELECT df.id, df.folder_name, df.entity_id, df.description,
           CASE 
               WHEN df.entity_type = 'patient' THEN p.full_name
               WHEN df.entity_type = 'professional' THEN u.name
               WHEN df.entity_type = 'employee' THEN u.name
           END as entity_name,
           (SELECT COUNT(*) FROM documents d WHERE d.folder_id = df.id AND d.deleted_at IS NULL) as document_count
    FROM document_folders df
    LEFT JOIN patients p ON df.entity_type = 'patient' AND df.entity_id = p.id
    LEFT JOIN users u ON df.entity_type IN ('professional', 'employee') AND df.entity_id = u.id
    WHERE df.entity_type = ?
    ORDER BY df.folder_name ASC
");
$foldersStmt->execute([$entityType]);
$folders = $foldersStmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar documentos da pasta selecionada
$documents = [];
if ($selectedFolderId > 0) {
    $docsStmt = db()->prepare("
        SELECT d.id, d.document_name, d.document_type, d.file_path, d.file_size,
               d.document_date, d.description, d.category, d.version,
               u.name as uploaded_by_name, d.created_at
        FROM documents d
        LEFT JOIN users u ON u.id = d.uploaded_by_user_id
        WHERE d.folder_id = ? AND d.deleted_at IS NULL
        ORDER BY d.document_date DESC, d.created_at DESC
    ");
    $docsStmt->execute([$selectedFolderId]);
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
if ($selectedFolderId > 0) {
    echo '<a class="btn btnPrimary" href="/documents_upload.php?folder_id=' . $selectedFolderId . '">📤 Upload Documento</a>';
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

// Grid com 2 colunas: Lista de pastas | Documentos da pasta
echo '<section class="card col4">';
echo '<div style="font-size:16px;font-weight:700;margin-bottom:12px">📂 Pastas</div>';
echo '<div style="max-height:600px;overflow-y:auto">';

if (count($folders) === 0) {
    echo '<div class="pill" style="padding:12px;text-align:center;color:#667781">Nenhuma pasta encontrada</div>';
} else {
    foreach ($folders as $folder) {
        $isSelected = $selectedFolderId === (int)$folder['id'];
        $bgColor = $isSelected ? '#e7f8f4' : '#f8f9fa';
        $borderColor = $isSelected ? '#00a884' : '#e5e7eb';
        
        echo '<a href="/documents_list.php?tab=' . $tab . '&folder_id=' . (int)$folder['id'] . '" style="display:block;padding:12px;margin-bottom:8px;background:' . $bgColor . ';border:2px solid ' . $borderColor . ';border-radius:8px;text-decoration:none;color:#111b21">';
        echo '<div style="font-weight:600;font-size:14px">' . h($folder['entity_name'] ?? $folder['folder_name']) . '</div>';
        echo '<div style="font-size:12px;color:#667781;margin-top:4px">' . (int)$folder['document_count'] . ' documento(s)</div>';
        echo '</a>';
    }
}

echo '</div>';
echo '</section>';

echo '<section class="card col8">';
echo '<div style="font-size:16px;font-weight:700;margin-bottom:12px">📄 Documentos</div>';

if ($selectedFolderId === 0) {
    echo '<div class="pill" style="padding:20px;text-align:center;color:#667781">';
    echo '← Selecione uma pasta para ver os documentos';
    echo '</div>';
} elseif (count($documents) === 0) {
    echo '<div class="pill" style="padding:20px;text-align:center;color:#667781">';
    echo 'Nenhum documento nesta pasta';
    echo '</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Nome</th><th>Tipo</th><th>Data</th><th>Categoria</th><th>Tamanho</th><th>Enviado por</th><th style="text-align:right">Ações</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($documents as $doc) {
        $fileSize = (int)$doc['file_size'];
        $fileSizeFormatted = $fileSize > 1048576 ? number_format($fileSize / 1048576, 2) . ' MB' : number_format($fileSize / 1024, 2) . ' KB';
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . h($doc['document_name']) . '</td>';
        echo '<td>' . h($doc['document_type'] ?? '-') . '</td>';
        echo '<td>' . ($doc['document_date'] ? date('d/m/Y', strtotime($doc['document_date'])) : '-') . '</td>';
        echo '<td>' . h($doc['category'] ?? '-') . '</td>';
        echo '<td>' . $fileSizeFormatted . '</td>';
        echo '<td>' . h($doc['uploaded_by_name'] ?? '-') . '</td>';
        echo '<td style="text-align:right">';
        echo '<a class="btn" href="' . h($doc['file_path']) . '" target="_blank">📥 Download</a> ';
        echo '<a class="btn" href="/documents_edit.php?id=' . (int)$doc['id'] . '">✏️ Editar</a>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
