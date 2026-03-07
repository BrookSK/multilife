<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$requirementId = isset($_GET['requirement_id']) ? (int)$_GET['requirement_id'] : 0;
$userId = auth_user_id();

if ($requirementId === 0) {
    header('Location: /faturamento_profissional.php');
    exit;
}

// Buscar requisito de documento
$stmt = db()->prepare("
    SELECT 
        bdr.*,
        pa.patient_id,
        pa.specialty,
        pa.service_type,
        pa.session_quantity,
        pa.payment_value,
        p.full_name as patient_name
    FROM billing_document_requirements bdr
    INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
    LEFT JOIN patients p ON p.id = pa.patient_id
    WHERE bdr.id = ? AND bdr.professional_user_id = ?
");
$stmt->execute([$requirementId, $userId]);
$requirement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$requirement) {
    header('Location: /faturamento_profissional.php');
    exit;
}

view_header('Enviar Documento de Comprovação');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div>';
echo '<a href="/faturamento_profissional.php" class="btn" style="margin-bottom:8px">← Voltar</a>';
echo '<div style="font-size:22px;font-weight:900">Enviar Documento de Comprovação</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Sessão ' . (int)$requirement['session_number'] . ' - ' . h($requirement['patient_name']) . '</div>';
echo '</div>';
echo '</section>';

// Informações do Atendimento
echo '<section class="card col6">';
echo '<h3>Informações do Atendimento</h3>';
echo '<table style="width:100%">';
echo '<tr><td style="font-weight:600;padding:8px 0">Paciente:</td><td>' . h($requirement['patient_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Especialidade:</td><td>' . h($requirement['specialty'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Tipo de Serviço:</td><td>' . h($requirement['service_type'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Sessão:</td><td>' . (int)$requirement['session_number'] . ' de ' . (int)$requirement['session_quantity'] . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Data da Sessão:</td><td>' . ($requirement['session_date'] ? date('d/m/Y', strtotime($requirement['session_date'])) : '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Valor:</td><td>R$ ' . number_format((float)$requirement['payment_value'], 2, ',', '.') . '</td></tr>';
echo '</table>';
echo '</section>';

// Instruções
echo '<section class="card col6" style="background:#f0f9ff;border-left:4px solid #0284c7">';
echo '<h3>Instruções</h3>';
echo '<ul style="margin:0;padding-left:20px;line-height:1.8">';
echo '<li>Envie as <strong>Fichas de Produtividade</strong> e <strong>Fichas de Faturamento</strong></li>';
echo '<li>Formatos aceitos: <strong>apenas JPEG e PNG</strong></li>';
echo '<li>Você pode tirar fotos diretamente pela câmera do celular</li>';
echo '<li>Envie até <strong>20 arquivos por tipo</strong> de documento</li>';
echo '<li>Após o envio, os documentos serão revisados pelo financeiro</li>';
echo '</ul>';
echo '</section>';

// Formulário de Upload
echo '<section class="card col12">';
echo '<h3>Upload dos Documentos</h3>';

if ($requirement['status'] === 'rejected' && $requirement['rejection_reason']) {
    echo '<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:16px;margin-bottom:16px">';
    echo '<div style="font-weight:700;color:#991b1b;margin-bottom:8px">Documento Rejeitado</div>';
    echo '<div style="color:#7f1d1d">' . h($requirement['rejection_reason']) . '</div>';
    echo '</div>';
}

echo '<form method="post" action="/faturamento_upload_doc_post.php" enctype="multipart/form-data" id="uploadForm">';
echo '<input type="hidden" name="requirement_id" value="' . $requirementId . '">';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Data da Sessão *</label>';
echo '<input type="date" name="session_date" required value="' . h($requirement['session_date'] ?? '') . '" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</div>';

// Fichas de Produtividade
echo '<div style="margin-bottom:24px;padding:20px;background:#f0fdf4;border-radius:8px;border:2px solid #10b981">';
echo '<h4 style="margin:0 0 16px 0;color:#065f46">📋 Fichas de Produtividade</h4>';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Selecione as imagens (até 20 arquivos) *</label>';
echo '<input type="file" name="produtividade[]" multiple accept="image/jpeg,image/png" capture="environment" style="width:100%;padding:10px;border:1px solid #10b981;border-radius:6px;background:white" onchange="previewFiles(this, \'produtividade-preview\')">';
echo '<div style="font-size:12px;color:#065f46;margin-top:4px">Apenas JPEG e PNG. Máx: 20 arquivos de 10MB cada</div>';
echo '<div id="produtividade-preview" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:10px;margin-top:16px"></div>';
echo '</div>';

// Fichas de Faturamento
echo '<div style="margin-bottom:24px;padding:20px;background:#eff6ff;border-radius:8px;border:2px solid #0284c7">';
echo '<h4 style="margin:0 0 16px 0;color:#0c4a6e">💰 Fichas de Faturamento</h4>';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Selecione as imagens (até 20 arquivos) *</label>';
echo '<input type="file" name="faturamento[]" multiple accept="image/jpeg,image/png" capture="environment" style="width:100%;padding:10px;border:1px solid #0284c7;border-radius:6px;background:white" onchange="previewFiles(this, \'faturamento-preview\')">';
echo '<div style="font-size:12px;color:#0c4a6e;margin-top:4px">Apenas JPEG e PNG. Máx: 20 arquivos de 10MB cada</div>';
echo '<div id="faturamento-preview" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:10px;margin-top:16px"></div>';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Observações</label>';
echo '<textarea name="notes" rows="4" placeholder="Informações adicionais sobre o atendimento..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px"></textarea>';
echo '</div>';

echo '<div style="display:flex;gap:10px">';
echo '<button type="submit" class="btn btnPrimary" id="submitBtn">Enviar Documentos</button>';
echo '<a href="/profissional_registros.php" class="btn">Cancelar</a>';
echo '</div>';

echo '</form>';

// JavaScript para preview e validação
echo '<script>';
echo 'function previewFiles(input, previewId) {';
echo '  const preview = document.getElementById(previewId);';
echo '  preview.innerHTML = "";';
echo '  const files = input.files;';
echo '  if (files.length > 20) {';
echo '    alert("Máximo de 20 arquivos por tipo");';
echo '    input.value = "";';
echo '    return;';
echo '  }';
echo '  for (let i = 0; i < files.length; i++) {';
echo '    const file = files[i];';
echo '    if (!file.type.match("image/(jpeg|png)")) {';
echo '      alert("Apenas arquivos JPEG e PNG são permitidos");';
echo '      input.value = "";';
echo '      preview.innerHTML = "";';
echo '      return;';
echo '    }';
echo '    if (file.size > 10 * 1024 * 1024) {';
echo '      alert("Arquivo " + file.name + " é muito grande. Máximo: 10MB");';
echo '      input.value = "";';
echo '      preview.innerHTML = "";';
echo '      return;';
echo '    }';
echo '    const reader = new FileReader();';
echo '    reader.onload = function(e) {';
echo '      const div = document.createElement("div");';
echo '      div.style.cssText = "position:relative;border:2px solid #e5e7eb;border-radius:6px;overflow:hidden";';
echo '      div.innerHTML = "<img src=\"" + e.target.result + "\" style=\"width:100%;height:100px;object-fit:cover\">";';
echo '      preview.appendChild(div);';
echo '    };';
echo '    reader.readAsDataURL(file);';
echo '  }';
echo '}';
echo 'document.getElementById("uploadForm").addEventListener("submit", function(e) {';
echo '  const prodFiles = document.querySelector("input[name=\'produtividade[]\']").files;';
echo '  const fatFiles = document.querySelector("input[name=\'faturamento[]\']").files;';
echo '  if (prodFiles.length === 0 && fatFiles.length === 0) {';
echo '    e.preventDefault();';
echo '    alert("Envie pelo menos um arquivo de Produtividade ou Faturamento");';
echo '    return false;';
echo '  }';
echo '  const btn = document.getElementById("submitBtn");';
echo '  btn.disabled = true;';
echo '  btn.textContent = "Enviando...";';
echo '});';
echo '</script>';
echo '</section>';

echo '</div>';

view_footer();
