<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare(
    'SELECT e.*, p.full_name AS patient_name
     FROM patient_prontuario_entries e
     INNER JOIN patients p ON p.id = e.patient_id
     WHERE e.id = :id AND p.deleted_at IS NULL'
);
$stmt->execute(['id' => $id]);
$e = $stmt->fetch();

if (!$e) {
    flash_set('error', 'Registro não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

$occurredAtValue = '';
try {
    $dt = new DateTime((string)$e['occurred_at']);
    $occurredAtValue = $dt->format('Y-m-d\TH:i');
} catch (Throwable $ex) {
    $occurredAtValue = '';
}

view_header('Editar prontuário');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Editar registro de prontuário</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">' . h((string)$e['patient_name']) . '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/patients_view.php?id=' . (int)$e['patient_id'] . '">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/patient_prontuario_edit_post.php" style="display:grid;gap:12px;max-width:820px">';
echo '<input type="hidden" name="id" value="' . (int)$e['id'] . '">';

echo '<div class="grid">';
echo '<div class="col6"><label>Origem<input name="origin" required maxlength="80" value="' . h((string)$e['origin']) . '"></label></div>';
echo '<div class="col6"><label>Data/hora<input type="datetime-local" name="occurred_at" required value="' . h($occurredAtValue) . '"></label></div>';
echo '<div class="col6"><label>Profissional (user_id, opcional)<input name="professional_user_id" inputmode="numeric" value="' . h((string)($e['professional_user_id'] ?? '')) . '" placeholder="Ex: 12"></label></div>';
echo '<div class="col6"><label>Qtd sessões (opcional)<input type="number" name="sessions_count" min="1" value="' . h((string)($e['sessions_count'] ?? '')) . '" placeholder="1"></label></div>';
echo '<div class="col12"><label>Observações<textarea name="notes" rows="4">' . h((string)($e['notes'] ?? '')) . '</textarea></label></div>';
echo '<div class="col12"><label>Anexos (JSON, opcional)<textarea name="attachments_json" rows="3" placeholder="{}">' . h((string)($e['attachments_json'] ?? '')) . '</textarea></label></div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/patients_view.php?id=' . (int)$e['patient_id'] . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
