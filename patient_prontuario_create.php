<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patient_prontuario.manage');

$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

$stmt = db()->prepare('SELECT * FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

view_header('Novo registro de prontuário');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Novo registro de prontuário</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">' . h((string)$patient['full_name']) . '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/patients_view.php?id=' . (int)$patient['id'] . '">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/patient_prontuario_create_post.php" style="display:grid;gap:12px;max-width:820px">';
echo '<input type="hidden" name="patient_id" value="' . (int)$patient['id'] . '">';

echo '<div class="grid">';
echo '<div class="col6"><label>Origem<input name="origin" required maxlength="80" placeholder="Ex: atendimento"></label></div>';
echo '<div class="col6"><label>Data/hora<input type="datetime-local" name="occurred_at" required></label></div>';
echo '<div class="col6"><label>Profissional (user_id, opcional)<input name="professional_user_id" inputmode="numeric" placeholder="Ex: 12"></label></div>';
echo '<div class="col6"><label>Qtd sessões (opcional)<input type="number" name="sessions_count" min="1" placeholder="1"></label></div>';
echo '<div class="col12"><label>Observações<textarea name="notes" rows="4"></textarea></label></div>';
echo '<div class="col12"><label>Anexos (JSON, opcional)<textarea name="attachments_json" rows="3" placeholder="{}"></textarea></label></div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/patients_view.php?id=' . (int)$patient['id'] . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
