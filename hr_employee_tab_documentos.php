<?php
// Aba de Documentos - Integrado com sistema de documentos

$employeeId = (int)$employee['id'];

// Buscar documentos do funcionário
$stmt = db()->prepare('
    SELECT d.*, u.name as uploaded_by_name
    FROM documents d
    LEFT JOIN users u ON u.id = d.uploaded_by_user_id
    WHERE d.entity_type = "hr_employee" AND d.entity_id = :entity_id
    ORDER BY d.created_at DESC
');
$stmt->execute(['entity_id' => $employeeId]);
$documents = $stmt->fetchAll();

echo '<div style="display:grid;gap:24px">';

// Header com botão de upload
echo '<div style="display:flex;justify-content:space-between;align-items:center">';
echo '<h3 style="font-size:18px;font-weight:700">Documentos do Funcionário</h3>';
echo '<button class="btn btnPrimary" onclick="document.getElementById(\'uploadForm\').style.display=\'block\'">📎 Upload Documento</button>';
echo '</div>';

// Formulário de upload (inicialmente oculto)
echo '<div id="uploadForm" class="card" style="display:none;padding:20px;background:hsl(var(--muted))">';
echo '<h4 style="font-size:16px;font-weight:700;margin-bottom:12px">Fazer Upload de Documento</h4>';
echo '<form method="post" action="/hr_employee_upload_document_post.php" enctype="multipart/form-data" style="display:grid;gap:12px">';
echo '<input type="hidden" name="employee_id" value="' . $employeeId . '">';

echo '<label>Título do Documento *<input name="title" required maxlength="160" placeholder="Ex: Contrato de Trabalho, RG, CPF"></label>';

echo '<label>Tipo de Documento<select name="document_type">';
echo '<option value="contract">Contrato</option>';
echo '<option value="id">Documento de Identidade</option>';
echo '<option value="address_proof">Comprovante de Endereço</option>';
echo '<option value="diploma">Diploma/Certificado</option>';
echo '<option value="exam">Exame Médico</option>';
echo '<option value="other">Outro</option>';
echo '</select></label>';

echo '<label>Arquivo *<input type="file" name="file" required></label>';

echo '<label>Observações<textarea name="notes" rows="2" placeholder="Observações sobre o documento"></textarea></label>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<button type="button" class="btn" onclick="document.getElementById(\'uploadForm\').style.display=\'none\'">Cancelar</button>';
echo '<button type="submit" class="btn btnPrimary">Fazer Upload</button>';
echo '</div>';

echo '</form>';
echo '</div>';

// Lista de documentos
if (count($documents) > 0) {
    echo '<div style="display:grid;gap:12px">';
    
    foreach ($documents as $doc) {
        $typeLabels = [
            'contract' => '📄 Contrato',
            'id' => '🪪 Documento de Identidade',
            'address_proof' => '🏠 Comprovante de Endereço',
            'diploma' => '🎓 Diploma/Certificado',
            'exam' => '🏥 Exame Médico',
            'other' => '📎 Outro'
        ];
        $typeLabel = $typeLabels[$doc['document_type']] ?? '📎 Documento';
        
        $fileSize = !empty($doc['file_size']) ? number_format((int)$doc['file_size'] / 1024, 0) . ' KB' : '';
        
        echo '<div class="card" style="padding:16px">';
        echo '<div style="display:flex;justify-content:space-between;align-items:start;gap:16px">';
        echo '<div style="flex:1">';
        echo '<div style="font-weight:700;font-size:16px;margin-bottom:4px">' . h((string)$doc['title']) . '</div>';
        echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;margin-bottom:4px">' . $typeLabel;
        if ($fileSize !== '') echo ' • ' . $fileSize;
        echo '</div>';
        if (!empty($doc['notes'])) {
            echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:8px">' . h((string)$doc['notes']) . '</div>';
        }
        echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:8px">';
        echo 'Enviado em ' . date('d/m/Y H:i', strtotime($doc['created_at']));
        if (!empty($doc['uploaded_by_name'])) {
            echo ' por ' . h((string)$doc['uploaded_by_name']);
        }
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px">';
        if (!empty($doc['file_path'])) {
            echo '<a class="btn" href="' . h((string)$doc['file_path']) . '" target="_blank" download>⬇️ Baixar</a>';
        }
        echo '<form method="post" action="/documents_delete_post.php" style="display:inline" onsubmit="return confirm(\'Excluir este documento?\')">';
        echo '<input type="hidden" name="id" value="' . (int)$doc['id'] . '">';
        echo '<input type="hidden" name="redirect" value="/hr_employee_profile.php?id=' . $employeeId . '&tab=documentos">';
        echo '<button class="btn" type="submit">🗑️ Excluir</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
} else {
    echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border-radius:12px">';
    echo '<div style="font-size:48px;margin-bottom:12px">📁</div>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum documento cadastrado</div>';
    echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Faça upload de contratos, documentos pessoais e outros arquivos.</div>';
    echo '</div>';
}

// Botões de navegação
echo '<div style="display:flex;gap:10px;justify-content:flex-end;padding-top:12px;border-top:2px solid hsl(var(--border))">';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '&tab=dependentes">← Anterior</a>';
echo '<a class="btn" href="/hr_employee_profile.php?id=' . $employeeId . '&tab=historico">Próximo →</a>';
echo '</div>';

echo '</div>';
