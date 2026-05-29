<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$patientId = (int)($_GET['patient_id'] ?? 0);

if ($patientId <= 0) {
    flash_set('error', 'Paciente inválido.');
    header('Location: /patients_list.php');
    exit;
}

$stmt = db()->prepare('SELECT id, full_name FROM patients WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

view_header('Enviar Documento - ' . (string)$patient['full_name']);

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Enviar Documento</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Paciente: <strong>' . h((string)$patient['full_name']) . '</strong></div>';
echo '</div>';
echo '<a class="btn" href="/patients_edit.php?id=' . $patientId . '">Voltar</a>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/patients_doc_upload_post.php" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:860px">';
echo '<input type="hidden" name="patient_id" value="' . $patientId . '">';

echo '<div class="grid">';

echo '<div class="col6"><label>Categoria<select name="doc_category" required>';
$docCategories = ['Atestado de Óbito', 'Atestado de Internação', 'Alta Hospitalar', 'Alta Definitiva', 'Laudo Médico', 'Receita/Prescrição', 'Exame Laboratorial', 'Exame de Imagem', 'Cartão SUS', 'Carteirinha Convênio', 'Documento de Identidade', 'Outro'];
echo '<option value="">— Selecione —</option>';
foreach ($docCategories as $cat) {
    echo '<option value="' . h($cat) . '">' . h($cat) . '</option>';
}
echo '</select></label></div>';

echo '<div class="col6"><label>Título/Descrição<input name="doc_title" maxlength="160" placeholder="Ex: Atestado de internação - Hospital X"></label></div>';

echo '<div class="col6"><label>Validade (opcional)<input type="date" name="doc_valid_until"></label></div>';

echo '<div class="col6"><label>Arquivo<input type="file" name="doc_file" required></label></div>';

echo '<div class="col12"><label>Observações<textarea name="doc_notes" rows="3" placeholder="Observações sobre o documento..."></textarea></label></div>';

echo '</div>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<a class="btn" href="/patients_edit.php?id=' . $patientId . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Enviar Documento</button>';
echo '</div>';

echo '</form>';
echo '</section>';
echo '</div>';

view_footer();
