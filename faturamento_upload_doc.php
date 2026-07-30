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
        COALESCE(pa.agreed_value, pa.payment_value) as payment_value,
        pa.agreed_value,
        pa.authorized_value,
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
echo '<section class="card col6" style="background:hsl(var(--muted));border-left:4px solid hsl(var(--primary))">';
echo '<h3>Instruções</h3>';
echo '<ul style="margin:0;padding-left:20px;line-height:1.8">';
echo '<li>Envie as <strong>Fichas de Produtividade</strong> e <strong>Fichas de Evolução</strong></li>';
echo '<li>Formatos aceitos: <strong>PDF, JPG, JPEG, PNG, WEBP, HEIC e HEIF</strong></li>';
echo '<li>Você pode tirar fotos diretamente pela câmera do celular ou enviar documentos em PDF</li>';
echo '<li>Envie até <strong>20 arquivos por tipo</strong> de documento</li>';
echo '<li>Após o envio, os documentos serão revisados pelo financeiro</li>';
echo '</ul>';
echo '</section>';

// Formulário de Upload
echo '<section class="card col12">';
echo '<h3>Upload dos Documentos</h3>';

if ($requirement['status'] === 'rejected' && $requirement['rejection_reason']) {
    echo '<div style="background:hsla(var(--destructive)/.1);border-left:4px solid hsl(var(--destructive));padding:16px;margin-bottom:16px">';
    echo '<div style="font-weight:700;color:hsl(var(--destructive));margin-bottom:8px">Documento Rejeitado</div>';
    echo '<div style="color:hsl(var(--destructive))">' . h($requirement['rejection_reason']) . '</div>';
    echo '</div>';
}

echo '<form method="post" action="/faturamento_upload_doc_post.php" enctype="multipart/form-data" id="uploadForm">';
echo '<input type="hidden" name="requirement_id" value="' . $requirementId . '">';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Data da Sessão *</label>';
echo '<input type="date" name="session_date" required value="' . h($requirement['session_date'] ?? '') . '" style="width:100%;padding:10px;border:1px solid hsl(var(--border));border-radius:calc(var(--radius));background:hsl(var(--background))">';
echo '</div>';

// Fichas de Produtividade
echo '<div style="margin-bottom:24px;padding:20px;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) + 4px);box-shadow:var(--shadow-card)">';
echo '<h4 style="margin:0 0 16px 0;color:hsl(var(--foreground))">Fichas de Produtividade</h4>';
echo '<div id="produtividade-uploads" style="display:flex;flex-direction:column;gap:12px"></div>';
echo '<button type="button" onclick="addFileUpload(\'produtividade\')" class="btn" style="width:100%;margin-top:12px;background:hsl(var(--muted));border:2px dashed hsl(var(--border))">+ Adicionar Arquivo</button>';
echo '</div>';

// Fichas de Evolução
echo '<div style="margin-bottom:24px;padding:20px;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) + 4px);box-shadow:var(--shadow-card)">';
echo '<h4 style="margin:0 0 16px 0;color:hsl(var(--foreground))">Fichas de Evolução</h4>';
echo '<div id="faturamento-uploads" style="display:flex;flex-direction:column;gap:12px"></div>';
echo '<button type="button" onclick="addFileUpload(\'faturamento\')" class="btn" style="width:100%;margin-top:12px;background:hsl(var(--muted));border:2px dashed hsl(var(--border))">+ Adicionar Arquivo</button>';
echo '</div>';

echo '<div style="margin-bottom:16px">';
echo '<label style="display:block;font-weight:600;margin-bottom:8px">Observações</label>';
echo '<textarea name="notes" rows="4" placeholder="Informações adicionais sobre o atendimento..." style="width:100%;padding:10px;border:1px solid hsl(var(--border));border-radius:calc(var(--radius));background:hsl(var(--background))"></textarea>';
echo '</div>';

echo '<div style="display:flex;gap:10px">';
echo '<button type="submit" class="btn btnPrimary" id="submitBtn">Enviar Documentos</button>';
echo '<a href="/profissional_registros.php" class="btn">Cancelar</a>';
echo '</div>';

echo '</form>';

// JavaScript para sistema de múltiplos uploads dinâmicos
echo '<script>';
echo 'const fileCounters = { produtividade: 0, faturamento: 0 };';
echo 'const fileData = { produtividade: {}, faturamento: {} };';
echo '';
echo 'function addFileUpload(type) {';
echo '  if (Object.keys(fileData[type]).length >= 20) {';
echo '    alert("Máximo de 20 arquivos por tipo");';
echo '    return;';
echo '  }';
echo '  ';
echo '  const container = document.getElementById(type + "-uploads");';
echo '  const fileId = type + "_" + fileCounters[type]++;';
echo '  ';
echo '  const fileBlock = document.createElement("div");';
echo '  fileBlock.id = "block_" + fileId;';
echo '  fileBlock.style.cssText = "padding:16px;background:hsl(var(--muted));border:1px solid hsl(var(--border));border-radius:calc(var(--radius));position:relative";';
echo '  ';
echo '  fileBlock.innerHTML = `';
echo '    <input type="file" ';
echo '      id="${fileId}" ';
echo '      name="${type}[]" ';
echo '      accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf" ';
echo '      capture="environment" ';
echo '      onchange="handleFileSelect(this, \'${fileId}\', \'${type}\')"
      style="display:none">';
echo '    <div id="preview_${fileId}" style="display:flex;align-items:center;gap:12px">';
echo '      <button type="button" onclick="document.getElementById(\'${fileId}\').click()" class="btn" style="background:hsl(var(--primary));color:hsl(var(--primary-foreground))">Escolher Arquivo</button>';
echo '      <span style="color:hsl(var(--muted-foreground));font-size:14px">Nenhum arquivo selecionado</span>';
echo '    </div>';
echo '    <button type="button" onclick="removeFileBlock(\'${fileId}\', \'${type}\')"
      style="position:absolute;top:8px;right:8px;background:hsl(var(--destructive));color:hsl(var(--destructive-foreground));border:none;border-radius:50%;width:28px;height:28px;cursor:pointer;font-weight:bold">×</button>';
echo '  `;';
echo '  ';
echo '  container.appendChild(fileBlock);';
echo '}';
echo '';
echo 'function handleFileSelect(input, fileId, type) {';
echo '  const file = input.files[0];';
echo '  if (!file) return;';
echo '  ';
echo '  const allowedTypes = ["image/jpeg","image/png","image/webp","image/heic","image/heif","application/pdf"];';
echo '  if (!allowedTypes.includes(file.type) && !file.name.match(/\\.(jpe?g|png|webp|heic|heif|pdf)$/i)) {';
echo '    alert("Formatos aceitos: PDF, JPG, JPEG, PNG, WEBP, HEIC e HEIF");';
echo '    input.value = "";';
echo '    return;';
echo '  }';
echo '  ';
echo '  if (file.size > 10 * 1024 * 1024) {';
echo '    alert("Arquivo muito grande. Máximo: 10MB");';
echo '    input.value = "";';
echo '    return;';
echo '  }';
echo '  ';
echo '  fileData[type][fileId] = file;';
echo '  ';
echo '  const preview = document.getElementById("preview_" + fileId);';
echo '  const reader = new FileReader();';
echo '  reader.onload = function(e) {';
echo '    preview.innerHTML = `';
echo '      <img src="${e.target.result}" style="width:80px;height:80px;object-fit:cover;border-radius:calc(var(--radius));border:1px solid hsl(var(--border))">';
echo '      <div style="flex:1">';
echo '        <div style="font-weight:600;color:hsl(var(--foreground))">${file.name}</div>';
echo '        <div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">${(file.size / 1024).toFixed(1)} KB</div>';
echo '      </div>';
echo '      <button type="button" onclick="document.getElementById(\'${fileId}\').click()" class="btn" style="font-size:12px">Alterar</button>';
echo '    `;';
echo '  };';
echo '  reader.readAsDataURL(file);';
echo '}';
echo '';
echo 'function removeFileBlock(fileId, type) {';
echo '  delete fileData[type][fileId];';
echo '  document.getElementById("block_" + fileId).remove();';
echo '}';
echo '';
echo 'document.getElementById("uploadForm").addEventListener("submit", function(e) {';
echo '  const totalFiles = Object.keys(fileData.produtividade).length + Object.keys(fileData.faturamento).length;';
echo '  if (totalFiles === 0) {';
echo '    e.preventDefault();';
echo '    alert("Envie pelo menos um arquivo de Produtividade ou Faturamento");';
echo '    return false;';
echo '  }';
echo '  const btn = document.getElementById("submitBtn");';
echo '  btn.disabled = true;';
echo '  btn.textContent = "Enviando...";';
echo '});';
echo '';
echo '// Adicionar primeiro bloco de cada tipo automaticamente';
echo 'window.addEventListener("DOMContentLoaded", function() {';
echo '  addFileUpload("produtividade");';
echo '  addFileUpload("faturamento");';
echo '});';
echo '</script>';
echo '</section>';

echo '</div>';

view_footer();
